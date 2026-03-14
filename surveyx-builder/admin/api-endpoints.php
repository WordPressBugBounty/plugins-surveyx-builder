<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Admin_API', false ) ) {
	class SurveyX_Admin_API {

		private static $instance;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		protected function __construct() {
			self::$instance = $this;
		}

		/**
		 * Get survey header info
		 * Returns only: id, title, survey_type, status, dates.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Returns minimal survey info.
		 */
		public function get_survey_header_info( WP_REST_Request $request ) {
			$body      = json_decode( $request->get_body(), true );
			$survey_id = absint( $body['id'] ?? 0 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey not found.', 'surveyx-builder' ) ],
					404
				);
			}

			$survey = SurveyX_Admin_Db::get_survey_by_id( $survey_id );

			// Keep only necessary fields for header
			$header_data = [
				'id'          => $survey['id'],
				'title'       => $survey['title'] ?? '',
				'survey_type' => $survey['survey_type'],
				'status'      => $survey['status'],
				'created_at'  => $survey['created_at'],
				'updated_at'  => $survey['updated_at'],
			];

			return new WP_REST_Response( [ 'data' => $header_data ], 200 );
		}

		/**
		 * Get full survey settings data (for general settings page).
		 * Returns all survey fields including settings JSON.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Returns full survey settings.
		 */
		public function get_survey_settings( WP_REST_Request $request ) {
			$body      = json_decode( $request->get_body(), true );
			$survey_id = absint( $body['id'] ?? 0 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey not found.', 'surveyx-builder' ) ],
					404
				);
			}

			$survey = SurveyX_Admin_Db::get_survey_by_id( $survey_id );

			if ( empty( $survey ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Failed to load survey.', 'surveyx-builder' ) ],
					500
				);
			}

			// Parse JSON fields
			$settings          = ! empty( $survey['settings'] ) ? json_decode( $survey['settings'], true ) : [];
			$survey['content'] = ! empty( $survey['content'] ) ? json_decode( $survey['content'], true ) : [];

			// Remove settings key before merging
			unset( $survey['settings'] );

			// Merge settings into root level
			$survey = array_merge( $survey, $settings );

			// Ensure correct database ID
			$survey['id'] = $survey_id;

			return new WP_REST_Response( [ 'data' => $survey ], 200 );
		}

		/**
		 * Retrieves survey data for editor based on the survey ID provided in the REST request body.
		 *
		 * Authorization is handled by permission_callback in REST route registration.
		 * Parses the JSON request body and extracts the survey ID.
		 * Sanitizes the survey ID before use.
		 * Returns appropriate WP_REST_Response on failure or success.
		 *
		 * @param WP_REST_Request $request The REST API request object containing the survey ID.
		 *
		 * @return WP_REST_Response Returns a REST response with survey data or error message.
		 */
		public function get_survey_editor_data( WP_REST_Request $request ) {
			$body      = json_decode( $request->get_body(), true );
			$survey_id = absint( $body['id'] ?? 0 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'The survey you are looking for could not be found.', 'surveyx-builder' ) ],
					404
				);
			}

			$result = SurveyX_Admin_Db::get_survey_editor_data( $survey_id );

			if ( empty( $result ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'An error occurred while retrieving the survey or it was not found in the database.', 'surveyx-builder' ) ],
					500 // Internal Server Error
				);
			}

			// prepare data for js
			$result['remove_question_ids'] = [];
			$result['remove_answer_ids']   = [];

			// Add flag to check if survey has saved content
			$result['has_saved_content'] = ! empty( $result['survey']['content'] );

			return new WP_REST_Response(
				[ 'data' => $result ],
				200
			);
		}

		/**
		 * Get survey overview data for Insights + Summary tabs.
		 * Uses only summary + questions + answers (lightweight).
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Returns overview data.
		 */
		public function get_survey_overview_data( WP_REST_Request $request ) {
			$body      = json_decode( $request->get_body(), true );
			$survey_id = absint( $body['id'] ?? 0 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'The survey you are looking for could not be found.', 'surveyx-builder' ) ],
					404
				);
			}

			// Update summary if not cached (6-hour cache per survey)
			surveyx_maybe_update_summary( $survey_id );

			$result = SurveyX_Analytics_Db::get_survey_overview( $survey_id );

			return new WP_REST_Response(
				[ 'data' => $result ],
				200
			);
		}

		/**
		 * Refresh survey summary and return updated overview.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Returns updated overview data.
		 */
		public function refresh_survey_analytics_data( WP_REST_Request $request ) {
			$body      = json_decode( $request->get_body(), true );
			$survey_id = absint( $body['id'] ?? 0 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'The survey you are looking for could not be found.', 'surveyx-builder' ) ],
					404
				);
			}

			// Clear cache and force update for this survey
			delete_transient( 'surveyx_summary_' . $survey_id );
			surveyx_update_summary( $survey_id );

			$result = SurveyX_Analytics_Db::refresh_survey_summary( $survey_id );

			return new WP_REST_Response(
				[
					'data'    => $result,
					'message' => esc_html__( 'Analytics data refreshed successfully.', 'surveyx-builder' ),
				],
				200
			);
		}

		/**
		 * Get surveys with server-side pagination, search, and sorting.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Returns paginated survey data.
		 */
		public function get_surveys_paginated( WP_REST_Request $request ) {
			$body = json_decode( $request->get_body(), true );

			$page       = absint( $body['page'] ?? 1 ) ?: 1;
			$per_page   = absint( $body['per_page'] ?? 10 ) ?: 10;
			$search     = sanitize_text_field( $body['search'] ?? '' );
			$sort_by    = sanitize_key( $body['sort_by'] ?? 'created_at' ) ?: 'created_at';
			$sort_order = sanitize_key( $body['sort_order'] ?? 'desc' ) ?: 'desc';

			$result = SurveyX_Admin_Db::get_surveys_paginated( $page, $per_page, $search, $sort_by, $sort_order );

			return new WP_REST_Response( [ 'data' => $result ], 200 );
		}

		/**
		 * Handles creating a new survey via REST API request.
		 *
		 * Validates the nonce from the request to ensure security.
		 * Parses and validates the JSON request body.
		 * Returns appropriate WP_REST_Response with error messages on failure.
		 *
		 * @param WP_REST_Request $request The REST API request object containing the survey data.
		 *
		 * @return WP_REST_Response Returns a REST response indicating success or error.
		 */
		public function create_survey( WP_REST_Request $request ) {
			// Get request body and sanitize data
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid JSON payload.', 'surveyx-builder' ) ],
					400 // Bad Request
				);
			}

			$data = SurveyX_Admin_Helpers::recursive_sanitize( $body );

			// Validate title
			if ( empty( $data['title'] ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey title is required.', 'surveyx-builder' ) ],
					400 // Bad Request
				);
			}

			$survey_type = $data['survey_type'] ?? 'vote';

			$result = SurveyX_Admin_Db::create_survey( $data['title'], $survey_type );

			if ( empty( $result ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'An error occurred while creating the survey in the database.', 'surveyx-builder' ) ],
					500 // Internal Server Error
				);
			}

			$created_survey = SurveyX_Admin_Db::get_survey_by_id( $result );

			return new WP_REST_Response(
				[
					'message' => sprintf(
						/* translators: %s is the title of the survey that was created successfully. */
						esc_html__( 'Survey "%s" created successfully!', 'surveyx-builder' ),
						$data['title']
					),
					'data'    => $created_survey,
				],
				200
			);
		}

		/**
		 * Handles the deletion of a survey via REST API.
		 *
		 * Authorization is handled by permission_callback in REST route registration.
		 * Checks that the request body is valid JSON. Returns a 400 error if validation fails.
		 *
		 * @param WP_REST_Request $request The REST request object containing the survey data.
		 *
		 * @return WP_REST_Response A response object containing success or error information.
		 */
		public function delete_survey( WP_REST_Request $request ) {
			// Get request body and sanitize data
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid JSON payload.', 'surveyx-builder' ) ],
					400 // Bad Request
				);
			}

			$data = SurveyX_Admin_Helpers::recursive_sanitize( $body );

			// Validate survey ID
			if ( empty( $data['id'] ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is required.', 'surveyx-builder' ) ],
					400 // Bad Request
				);
			}

			$survey_id    = (int) $data['id'];
			$survey_title = $data['title'] ?? $data['id'];

			// Check if survey exists
			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					/* translators: %d is the ID of the survey that could not be found. */
					[ 'message' => sprintf( esc_html__( 'Survey with ID %d could not be found.', 'surveyx-builder' ), $survey_id ) ],
					404 // Not Found
				);
			}

			$result = SurveyX_Admin_Db::delete_survey( $survey_id );

			if ( false === $result ) {
				return new WP_REST_Response(
					/* translators: %d is the ID of the survey that could not be deleted. */
					[ 'message' => sprintf( esc_html__( 'Unable to delete the survey with ID: %d. Please try again.', 'surveyx-builder' ), $survey_id ) ],
					500 // Internal Server Error
				);
			}

			// Clean up related data
			SurveyX_Admin_Db::delete_answers_by_survey_id( $survey_id );
			SurveyX_Admin_Db::delete_questions_by_survey_id( $survey_id );
			SurveyX_Admin_Db::delete_responses_by_survey_id( $survey_id );

			return new WP_REST_Response(
				[
					'data'    => $survey_id,
					'message' => sprintf(
						/* translators: %s is the title of the survey that was deleted. */
						esc_html__( 'Survey "%s" was deleted successfully.', 'surveyx-builder' ),
						$survey_title
					),
				],
				200
			);
		}

		/**
		 * Handles updating an existing survey via REST API.
		 *
		 * Authorization is handled by permission_callback in REST route registration.
		 * Decodes and validates the request body, then processes the survey update.
		 *
		 * @param WP_REST_Request $request The REST API request object containing survey data.
		 *
		 * @return WP_REST_Response Returns a WP_REST_Response object with success or error message.
		 */
		public function quick_update_survey( WP_REST_Request $request ) {
			// Decode JSON body
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid request data.', 'surveyx-builder' ) ],
					400
				);
			}

			// Recursively sanitize and convert all fields (strings, booleans, etc.)
			$data = SurveyX_Admin_Helpers::recursive_sanitize( $body );

			$survey_id = $data['id'] ?? 0;

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing or invalid.', 'surveyx-builder' ) ],
					400
				);
			}

			// validated update_fields
			$update_fields = [
				'survey_type' => $data['survey_type'] ?? 'vote',
				'status'      => $data['status'] ?? 'inactive',
				'settings'    => $data,
			];

			$result = SurveyX_Admin_Db::quick_update_survey( $survey_id, $update_fields );

			if ( 0 === $result ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'No changes were made.', 'surveyx-builder' ) ],
					200
				);
			}

			if ( false === $result ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Failed to update survey. Please try again.', 'surveyx-builder' ) ],
					500
				);
			}

			return new WP_REST_Response(
				[
					'message' => sprintf(
						/* translators: %s is the title of the survey that was updated. */
						esc_html__( 'Survey "%s" updated successfully!', 'surveyx-builder' ),
						$data['title'] ?? ''
					),
				],
				200
			);
		}

		/**
		 * Updates an existing survey via REST API.
		 *
		 * Authorization is handled by permission_callback in REST route registration.
		 * This endpoint decodes the JSON body and performs the necessary updates to the survey record.
		 * Returns a proper REST response with appropriate status codes for success or failure.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 *                                 Expected to contain JSON body with survey data to update.
		 *
		 * @return WP_REST_Response A REST response object containing either
		 *                          success data or an error message with HTTP status code:
		 *                          - 400 if the request body is invalid.
		 *                          - 200 on successful update.
		 *
		 */
		public function update_survey( WP_REST_Request $request ) {
			// Decode JSON body
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid request data.', 'surveyx-builder' ) ],
					400
				);
			}

			// Recursively sanitize and convert all fields (strings, booleans, etc.)
			$data = SurveyX_Admin_Helpers::recursive_sanitize( $body );

			$survey_id = absint( $data['id'] ?? 0 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing or invalid.', 'surveyx-builder' ) ],
					400
				);
			}

			// allow modify data before save
			do_action( 'surveyx_before_doing_save', $body );

			$questions           = is_array( $data['questions'] ?? null ) ? $data['questions'] : [];
			$answers             = is_array( $data['answers'] ?? null ) ? $data['answers'] : [];
			$remove_question_ids = is_array( $data['remove_question_ids'] ?? null ) ? $data['remove_question_ids'] : [];
			$remove_answer_ids   = is_array( $data['remove_answer_ids'] ?? null ) ? $data['remove_answer_ids'] : [];
			$survey_data         = is_array( $data['survey'] ?? null ) ? $data['survey'] : [];

			// Update survey Data
			$result = SurveyX_Admin_Db::update_survey_base( $survey_id, $survey_data );

			if ( false === $result ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Error during saving survey. Please try again.', 'surveyx-builder' ) ],
					500
				);
			}

			// Update questions & answers - returns ID mapping
			$id_mapping = SurveyX_Admin_Db::update_questions_and_answers_base( $survey_id, $questions, $answers );

			SurveyX_Admin_Db::delete_questions( $survey_id, $remove_question_ids );
			SurveyX_Admin_Db::delete_answers( $survey_id, $remove_answer_ids );

			// Update temp IDs to real IDs in all revisions
			if ( ! empty( $id_mapping['questions'] ) || ! empty( $id_mapping['answers'] ) ) {
				SurveyX_Revisions::update_ids_in_revisions( $survey_id, $id_mapping );
			}

			// Delete autosave after successful save
			SurveyX_Revisions::delete_autosave( $survey_id );

			// Return a new update
			$result = SurveyX_Admin_Db::get_survey_editor_data( $survey_id );

			return new WP_REST_Response(
				[
					'data'    => $result,
					'message' => sprintf(
						/* translators: %s is the title of the survey that was updated. */
						esc_html__( 'Survey "%s" updated successfully!', 'surveyx-builder' ),
						$result['survey']['title'] ?? ''
					),
				],
				200
			);
		}

		/**
		 * Get SurveyX settings via REST API.
		 *
		 * @return WP_REST_Response Settings data.
		 */
		public function get_settings() {
			$settings = SurveyX_Admin_Db::get_settings();
			return new WP_REST_Response( [ 'data' => $settings ], 200 );
		}

		/**
		 * Updates integration settings for reCAPTCHA v2 based on the provided REST request.
		 *
		 * Authorization is handled by permission_callback in REST route registration.
		 * This function processes a REST API request to update reCAPTCHA v2 settings, including
		 * enabling/disabling reCAPTCHA v2 and setting the site and secret keys.
		 * Sanitizes input data, validates required fields, and updates the settings in the database.
		 *
		 * @param WP_REST_Request $request The REST request object containing the settings data in the request body.
		 *
			 * @return WP_REST_Response A response object containing a success or error message and an appropriate HTTP status code.
			 *     - Returns 400 if reCAPTCHA v2 is enabled but site or secret keys are missing.
			 *     - Returns 200 with a success message if the settings are updated successfully.
			 */
		public function update_settings( WP_REST_Request $request ) {
			$data = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid request data.', 'surveyx-builder' ) ],
					400
				);
			}

			$sanitized = SurveyX_Admin_Helpers::recursive_sanitize( $data );

			$result = SurveyX_Admin_Db::update_settings( $sanitized );

			if ( false === $result ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Failed to update settings. Please try again.', 'surveyx-builder' ) ],
					500
				);
			}

			return new WP_REST_Response(
				// translators: %s is the setting category name (e.g., "Email Notifications", "Alphabet Labels")
				[ 'message' => esc_html__( '%s updated successfully', 'surveyx-builder' ) ],
				200
			);
		}

		/**
		 * Fetches survey templates from the remote API server or from the local cache.
		 *
		 * Authorization is handled by permission_callback in REST route registration.
		 * If cached templates exist and have not expired, the cached data will be returned.
		 * Otherwise, it will attempt to fetch fresh templates from the remote server and
		 * cache them for 5 days.
		 *
		 * If the remote server request fails, an error will be logged and an admin notice
		 * will be displayed in the WordPress dashboard, while falling back to cached data
		 * (if available).
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 *
		 * @return WP_REST_Response Returns a REST response containing:
		 *                          - data:   array of templates (empty array if unavailable).
		 *                          - message: success message.
		 *                          Response code 200 on success.
		 * @since 1.0.0
		 *
		 */
		public function fetch_remote_templates( WP_REST_Request $_request ) {

			$timeout = 2 * DAY_IN_SECONDS;

			if ( SurveyX_Admin_Helpers::is_dev_mode() ) {
				$timeout = 300;
			}

			$templates_cache = get_transient( 'surveyx_templates' );

			if ( false === $templates_cache ) {
				$templates = [];

				$response = wp_remote_post(
					SURVEYX_HOST_BASE . '/templates/wp-json/surveyx/templates',
					SurveyX_Admin_Helpers::get_remote_request_args()
				);

				if ( ! is_wp_error( $response ) ) {
					$body         = wp_remote_retrieve_body( $response );
					$body_decoded = json_decode( $body, true );
					if ( ! empty( $body_decoded['data'] ) ) {
						$templates = SurveyX_Admin_Helpers::recursive_sanitize( $body_decoded['data'] );
						set_transient( 'surveyx_templates', $templates, $timeout );
						$templates_cache = $templates;
					}
				}
			}

			if ( empty( $templates_cache ) ) {
				return new WP_REST_Response(
					[
						'data'    => [],
						'message' => esc_html__( 'No templates available. Could not fetch from cache or remote server.', 'surveyx-builder' ),
					],
					500 // Internal Server Error
				);
			}

			return new WP_REST_Response(
				[
					'data'    => $templates_cache,
					'message' => esc_html__( 'Templates retrieved successfully.', 'surveyx-builder' ),
				],
				200
			);
		}

		/**
		 * Fetch remote docs from external API for helps page.
		 * Data is cached for 15 days.
		 *
		 * @param WP_REST_Request $_request The REST API request object.
		 * @return WP_REST_Response Docs data or error.
		 */
		public function fetch_remote_docs( WP_REST_Request $_request ) {
			$timeout = 15 * DAY_IN_SECONDS; // 15 days cache

			if ( SurveyX_Admin_Helpers::is_dev_mode() ) {
				$timeout = 300; // 5 minutes in debug mode
			}

			$cache_key  = 'surveyx_docs';
			$docs_cache = get_transient( $cache_key );

			if ( false === $docs_cache ) {
				$docs = [];

				$response = wp_remote_post(
					SURVEYX_HOST_BASE . '/templates/wp-json/surveyx/docs',
					SurveyX_Admin_Helpers::get_remote_request_args()
				);

				if ( ! is_wp_error( $response ) ) {
					$body         = wp_remote_retrieve_body( $response );
					$body_decoded = json_decode( $body, true );
					if ( ! empty( $body_decoded['data'] ) ) {
						$docs = SurveyX_Admin_Helpers::recursive_sanitize( $body_decoded['data'] );
						set_transient( $cache_key, $docs, $timeout );
						$docs_cache = $docs;
					}
				}
			}

			if ( empty( $docs_cache ) ) {
				return new WP_REST_Response(
					[
						'data'    => [],
						'message' => esc_html__( 'No docs available. Could not fetch from cache or remote server.', 'surveyx-builder' ),
					],
					500
				);
			}

			return new WP_REST_Response(
				[
					'data'    => $docs_cache,
					'message' => esc_html__( 'Docs retrieved successfully.', 'surveyx-builder' ),
				],
				200
			);
		}

		/**
		 * Fetch remote notifications from external server with caching.
		 * Caches for 1 day (or 5 minutes in debug mode).
		 *
		 * @param WP_REST_Request $_request The REST API request object.
		 * @return WP_REST_Response Returns notifications data.
		 */
		public function fetch_remote_notifications( WP_REST_Request $_request ) {
			$timeout = DAY_IN_SECONDS; // 1 day cache

			if ( SurveyX_Admin_Helpers::is_dev_mode() ) {
				$timeout = 300; // 5 minutes in debug mode
			}

			$cache_key           = 'surveyx_notifications';
			$notifications_cache = get_transient( $cache_key );

			if ( false === $notifications_cache ) {
				$notifications = [];

				$response = wp_remote_post(
					SURVEYX_HOST_BASE . '/templates/wp-json/surveyx/notifications',
					SurveyX_Admin_Helpers::get_remote_request_args()
				);

				if ( ! is_wp_error( $response ) ) {
					$body         = wp_remote_retrieve_body( $response );
					$body_decoded = json_decode( $body, true );
					if ( ! empty( $body_decoded['data'] ) ) {
						$notifications = SurveyX_Admin_Helpers::recursive_sanitize( $body_decoded['data'] );
						set_transient( $cache_key, $notifications, $timeout );
						$notifications_cache = $notifications;
					}
				}
			}

			if ( empty( $notifications_cache ) ) {
				return new WP_REST_Response(
					[
						'data'    => [],
						'message' => esc_html__( 'No notifications available.', 'surveyx-builder' ),
					],
					200
				);
			}

			return new WP_REST_Response(
				[
					'data'    => $notifications_cache,
					'message' => esc_html__( 'Notifications retrieved successfully.', 'surveyx-builder' ),
				],
				200
			);
		}

		/**
		 * Smart autosave survey data.
		 * Types: 'autosave' (overwrites), 'snapshot' (interval-based), 'manual'
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Returns revision ID and saved timestamp.
		 */
		public function autosave_survey( WP_REST_Request $request ) {
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid request data.', 'surveyx-builder' ) ],
					400
				);
			}

			$survey_id = absint( $body['survey_id'] ?? 0 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey not found.', 'surveyx-builder' ) ],
					404
				);
			}

			// Get revision type from request (default: autosave)
			$type = sanitize_text_field( $body['type'] ?? 'autosave' );
			if ( ! in_array( $type, [ 'autosave', 'snapshot', 'manual' ], true ) ) {
				$type = 'autosave';
			}

			// Prepare data for revision (sanitize)
			$data = [
				'survey'    => SurveyX_Admin_Helpers::recursive_sanitize( $body['survey'] ?? [] ),
				'questions' => SurveyX_Admin_Helpers::recursive_sanitize( $body['questions'] ?? [] ),
				'answers'   => SurveyX_Admin_Helpers::recursive_sanitize( $body['answers'] ?? [] ),
			];

			// Save revision (may return null if snapshot skipped)
			$revision_id = SurveyX_Revisions::save_revision( $survey_id, $data, $type );

			// Get saved_at in UTC and convert to local for frontend display
			$saved_at_utc   = surveyx_get_utc_now();
			$saved_at_local = surveyx_utc_to_local( $saved_at_utc );

			return new WP_REST_Response(
				[
					'success'     => true,
					'revision_id' => $revision_id,
					'saved_at'    => $saved_at_local,
					'type'        => $type,
				],
				200
			);
		}

		/**
		 * Get autosave status for a survey.
		 * Returns whether there's a newer autosave than the last saved version.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Returns autosave status info.
		 */
		public function get_autosave_status( WP_REST_Request $request ) {
			$survey_id = $request->get_param( 'id' );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			$survey_id = absint( $survey_id );

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey not found.', 'surveyx-builder' ) ],
					404
				);
			}

			// Get survey's last updated timestamp
			$survey          = SurveyX_Admin_Db::get_survey_by_id( $survey_id );
			$last_updated_at = $survey['updated_at'] ?? null;

			// Get autosave status
			$status = SurveyX_Revisions::get_autosave_status( $survey_id, $last_updated_at );

			return new WP_REST_Response( $status, 200 );
		}

		/**
		 * Restore survey from a revision.
		 * Returns the full revision data for the editor to load.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Returns revision data.
		 */
		public function restore_revision( WP_REST_Request $request ) {
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid request data.', 'surveyx-builder' ) ],
					400
				);
			}

			$survey_id   = absint( $body['survey_id'] ?? 0 );
			$revision_id = absint( $body['revision_id'] ?? 0 );

			if ( ! $survey_id || ! $revision_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID or Revision ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			// Get revision
			$revision = SurveyX_Revisions::get_revision( $revision_id );

			if ( ! $revision || (int) $revision->survey_id !== $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Revision not found.', 'surveyx-builder' ) ],
					404
				);
			}

			return new WP_REST_Response(
				[
					'success' => true,
					'data'    => $revision->data,
				],
				200
			);
		}

		/**
		 * Get revision history for a survey.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Returns list of revisions.
		 */
		public function get_revisions( WP_REST_Request $request ) {
			$survey_id = $request->get_param( 'id' );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			$survey_id = absint( $survey_id );

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey not found.', 'surveyx-builder' ) ],
					404
				);
			}

			$revisions = SurveyX_Revisions::get_revisions( $survey_id, 5 );

			return new WP_REST_Response(
				[
					'success'   => true,
					'revisions' => $revisions,
				],
				200
			);
		}
		/**
		 * Start template import with progress tracking.
		 * Uses fastcgi_finish_request() to return response immediately,
		 * then continues processing import in background.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Response with import_id.
		 */
		public function start_import( WP_REST_Request $request ) {

			$body        = json_decode( $request->get_body(), true );
			$template_id = sanitize_text_field( $body['template_id'] ?? '' );

			if ( empty( $template_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Template ID is required.', 'surveyx-builder' ) ],
					400
				);
			}

			require_once SURVEYX_PATH . 'admin/import-handler.php';

			// Use template_id as import_id so client can poll immediately
			$import_id = 'tpl_' . $template_id;

			// Process import synchronously - progress updates stored in transient
			// Client polls /import/progress with import_id in parallel to get real-time updates
			$result = SurveyX_Import_Handler::process_import( $import_id, $template_id );

			// Return result with import_id
			if ( isset( $result['error'] ) ) {
				return new WP_REST_Response(
					[
						'import_id' => $import_id,
						'message'   => $result['error'],
					],
					500
				);
			}

			return new WP_REST_Response(
				[
					'import_id' => $import_id,
					'survey_id' => $result['survey_id'],
					'data'      => $result['data'],
				],
				200
			);
		}

		/**
		 * Get text responses for drawer (lazy load via POST).
		 * Validates answer_type against allowed types (extendable via filter).
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Text responses with pagination.
		 */
		public function get_text_responses( WP_REST_Request $request ) {
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid request data.', 'surveyx-builder' ) ],
					400
				);
			}

			$survey_id   = absint( $body['survey_id'] ?? 0 );
			$question_id = absint( $body['question_id'] ?? 0 );
			$answer_type = sanitize_text_field( $body['answer_type'] ?? 'text_input' );
			$offset      = absint( $body['offset'] ?? 0 );
			$limit       = min( absint( $body['limit'] ?? 50 ), 100 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			if ( ! $question_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Question ID is missing.', 'surveyx-builder' ) ],
					400
				);
			}

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey not found.', 'surveyx-builder' ) ],
					404
				);
			}

			// Validate answer_type against allowed types (extendable via filter)
			$allowed_types = apply_filters( 'surveyx_allowed_text_response_types', [ 'text_input', 'other' ] );

			if ( ! in_array( $answer_type, $allowed_types, true ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid answer type.', 'surveyx-builder' ) ],
					400
				);
			}

			$responses = SurveyX_Analytics_Db::get_text_responses(
				$survey_id,
				$question_id,
				$answer_type,
				$offset,
				$limit
			);

			$total = SurveyX_Analytics_Db::get_text_responses_count(
				$survey_id,
				$question_id,
				$answer_type
			);

			// Format responses based on type
			$formatted = array_map(
				function ( $row ) use ( $answer_type ) {
					$content = json_decode( $row->response_content, true );
					return [
						'id'          => (int) $row->id,
						'content'     => $content,
						'message'     => self::extract_text_display( $content, $answer_type ),
						'answered_at' => $row->answered_at,
					];
				},
				$responses
			);

			return new WP_REST_Response(
				[
					'responses' => $formatted,
					'total'     => $total,
				],
				200
			);
		}

		/**
		 * Extract display text from response content based on answer type.
		 *
		 * @param array  $content     Response content array.
		 * @param string $answer_type Type of response.
		 * @return string Display text.
		 */
		private static function extract_text_display( $content, $answer_type ) {
			if ( ! is_array( $content ) ) {
				return '';
			}

			switch ( $answer_type ) {
				case 'text_input':
					return $content['message'] ?? '';
				case 'other':
					return $content['other_text'] ?? '';
				default:
					// Extended types handled via filter
					return apply_filters( 'surveyx_extract_text_display', '', $content, $answer_type );
			}
		}

		/**
		 * Get import progress.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Progress data or error.
		 */
		public function get_import_progress( WP_REST_Request $request ) {
			$import_id = $request->get_param( 'import_id' );

			if ( empty( $import_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Import ID is required.', 'surveyx-builder' ) ],
					400
				);
			}

			require_once SURVEYX_PATH . 'admin/import-handler.php';

			$progress = SurveyX_Import_Handler::get_progress( sanitize_text_field( $import_id ) );

			if ( ! $progress ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Import not found.', 'surveyx-builder' ) ],
					404
				);
			}

			return new WP_REST_Response( $progress, 200 );
		}
	}
}

/** load */
SurveyX_Admin_API::get_instance();
