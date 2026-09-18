<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Admin_API', false ) ) {
	/**
	 * REST handlers for the admin SPA.
	 *
	 * Every route these methods serve is authorized by its permission_callback at
	 * registration time (admin/rest-routes.php, manage_options); no handler here
	 * re-checks capabilities.
	 */
	class SurveyX_Admin_API {

		/**
		 * Singleton instance.
		 *
		 * @var SurveyX_Admin_API
		 */
		private static $instance;

		/**
		 * Get the singleton instance.
		 *
		 * @return SurveyX_Admin_API
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		protected function __construct() {
			self::$instance = $this;
		}

		/**
		 * Blocks editing of a Pro survey in the free version.
		 *
		 * Returns a 403 response for a Pro survey (the admin app shows an upgrade
		 * notice), or null to proceed.
		 *
		 * @param int $survey_id The survey ID.
		 * @return WP_REST_Response|null
		 */
		private function block_if_pro_survey( $survey_id ) {
			/*
			 * The same predicate the shortcode, the standalone page and the public REST
			 * gate use, so this door cannot refuse a survey the others let through.
			 *
			 * An unstamped row — any INSERT that omitted the column, which this NOT NULL
			 * varchar silently stores as '' — is classified on the spot rather than
			 * guessed: multi-question means refuse, because Free's editor keeps one
			 * question and saving would discard the rest, while a lone free-renderable
			 * question is a survey Free can edit and is let through.
			 */
			if ( SurveyX_Db::survey_needs_pro( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'This survey uses Pro features. Please activate SurveyX Pro to edit it.', 'surveyx-builder' ) ],
					403
				);
			}

			return null;
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

			$blocked = $this->block_if_pro_survey( $survey_id );
			if ( $blocked ) {
				return $blocked;
			}

			$survey = SurveyX_Admin_Db::get_survey_by_id( $survey_id );

			$header_data = [
				'id'          => $survey['id'],
				'title'       => $survey['title'] ?? '',
				'survey_type' => $survey['survey_type'],
				'status'      => $survey['status'],
				'created_at'  => $survey['created_at'],
				'updated_at'  => $survey['updated_at'],
				/*
				 * Canonical standalone page URL, or '' when a request for it would 404:
				 * `page_mode` (or the site-wide switch it may defer to) withholds the page,
				 * the survey is not active, or the shortcode's access gate refuses it —
				 * expired, or no questions. A login-required survey keeps its URL: the page
				 * renders, it just answers with the login notice. The row is passed in so the
				 * test reuses the settings and content already loaded above.
				 *
				 * Derived, never stored — quick_update_survey() strips it from the posted
				 * survey. The site-wide switch deliberately does NOT ride along: the client
				 * posts this payload straight back, so a site-wide value placed here would be
				 * persisted per survey. It is bootstrapped once in `surveyxAdminConfigs`.
				 */
				'page_url'    => class_exists( 'SurveyX_Public_Page' )
					? SurveyX_Public_Page::get_survey_url( $survey['id'], $survey['title'] ?? '', $survey )
					: '',
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

			$blocked = $this->block_if_pro_survey( $survey_id );
			if ( $blocked ) {
				return $blocked;
			}

			$survey = SurveyX_Admin_Db::get_survey_by_id( $survey_id );

			if ( empty( $survey ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Failed to load survey.', 'surveyx-builder' ) ],
					500
				);
			}

			$settings          = ! empty( $survey['settings'] ) ? json_decode( $survey['settings'], true ) : [];
			$survey['content'] = ! empty( $survey['content'] ) ? json_decode( $survey['content'], true ) : [];

			unset( $survey['settings'] );

			// Settings is the BASE and the columns override it: an authoritative column
			// (content, status, updated_at, …) must win over a stale same-named key that
			// legacy data may still carry inside the settings blob.
			$survey = array_merge( $settings, $survey );

			// Same reason — the merge can surface a stale `id` from the blob.
			$survey['id'] = $survey_id;

			return new WP_REST_Response( [ 'data' => $survey ], 200 );
		}

		/**
		 * Retrieves survey data for editor based on the survey ID provided in the REST request body.
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

			$blocked = $this->block_if_pro_survey( $survey_id );
			if ( $blocked ) {
				return $blocked;
			}

			$result = SurveyX_Admin_Db::get_survey_editor_data( $survey_id );

			if ( empty( $result ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'An error occurred while retrieving the survey or it was not found in the database.', 'surveyx-builder' ) ],
					500
				);
			}

			// Scratch lists the editor fills in as the author deletes rows; always present
			// in the response so the client never has to create them.
			$result['remove_question_ids'] = [];
			$result['remove_answer_ids']   = [];

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

			// get_survey_overview() owns the measurement and its fifteen-minute cache.
			// Measuring here as well would do every aggregate twice.
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

			// refresh_survey_summary() forces the measurement and re-arms the cache.
			// Do not measure here as well — that is the same aggregate queries twice.
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

			// Canonical standalone page URL for the list's share/embed actions. The site-wide
			// switch is deliberately not stamped onto every row: it is one site setting,
			// bootstrapped once in `surveyxAdminConfigs`.
			if ( ! empty( $result['items'] ) && class_exists( 'SurveyX_Public_Page' ) ) {
				// ONE query for the whole page (settings blob, status, a computed "has
				// content" flag per row), so the per-row resolution below reads the primed
				// cache instead of a SELECT each. The row in hand is deliberately NOT passed
				// to get_survey_url(): this listing query selects neither `settings` nor
				// `content`, so only the primed cache is a complete source.
				SurveyX_Public_Page::prime_page_cache( wp_list_pluck( $result['items'], 'id' ) );

				foreach ( $result['items'] as $index => $item ) {
					// '' when that page would 404, so the list never offers a dead link; see
					// get_survey_header_info() for the full condition.
					$result['items'][ $index ]['page_url'] = SurveyX_Public_Page::get_survey_url(
						$item['id'],
						$item['title'] ?? ''
					);
				}
			}

			return new WP_REST_Response( [ 'data' => $result ], 200 );
		}

		/**
		 * Handles creating a new survey via REST API request.
		 *
		 * @param WP_REST_Request $request The REST API request object containing the survey data.
		 *
		 * @return WP_REST_Response Returns a REST response indicating success or error.
		 */
		public function create_survey( WP_REST_Request $request ) {
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid JSON payload.', 'surveyx-builder' ) ],
					400
				);
			}

			$data = SurveyX_Admin_Helpers::recursive_sanitize( $body );

			if ( empty( $data['title'] ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey title is required.', 'surveyx-builder' ) ],
					400
				);
			}

			$survey_type = $data['survey_type'] ?? 'vote';

			$result = SurveyX_Admin_Db::create_survey( $data['title'], $survey_type );

			if ( empty( $result ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'An error occurred while creating the survey in the database.', 'surveyx-builder' ) ],
					500
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
		 * @param WP_REST_Request $request The REST request object containing the survey data.
		 *
		 * @return WP_REST_Response A response object containing success or error information.
		 */
		public function delete_survey( WP_REST_Request $request ) {
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid JSON payload.', 'surveyx-builder' ) ],
					400
				);
			}

			$data = SurveyX_Admin_Helpers::recursive_sanitize( $body );

			if ( empty( $data['id'] ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is required.', 'surveyx-builder' ) ],
					400
				);
			}

			$survey_id    = (int) $data['id'];
			$survey_title = $data['title'] ?? $data['id'];

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					/* translators: %d is the ID of the survey that could not be found. */
					[ 'message' => sprintf( esc_html__( 'Survey with ID %d could not be found.', 'surveyx-builder' ), $survey_id ) ],
					404
				);
			}

			$result = SurveyX_Admin_Db::delete_survey( $survey_id );

			if ( false === $result ) {
				return new WP_REST_Response(
					/* translators: %d is the ID of the survey that could not be deleted. */
					[ 'message' => sprintf( esc_html__( 'Unable to delete the survey with ID: %d. Please try again.', 'surveyx-builder' ), $survey_id ) ],
					500
				);
			}

			SurveyX_Admin_Db::delete_answers_by_survey_id( $survey_id );
			SurveyX_Admin_Db::delete_questions_by_survey_id( $survey_id );
			SurveyX_Admin_Db::delete_responses_by_survey_id( $survey_id );
			SurveyX_Admin_Db::delete_revisions_by_survey_id( $survey_id );

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
		 * The posted settings are MERGED onto the stored blob, never replaced. Not every
		 * caller holds the whole survey — the Editor tab loads only /survey/header-info —
		 * and a plain replace wrote `[]` over every saved setting (theme, branding, gates)
		 * behind an "updated successfully" toast with no undo. A key the caller DID send
		 * always wins, including false, 0, '' and null, so switching a toggle off persists;
		 * only keys the payload never mentions are inherited, which is lossless because no
		 * admin control deletes a key — they all write a value. Template import replaces the
		 * blob wholesale, but through import_survey_data(), not this endpoint.
		 *
		 * @param WP_REST_Request $request The REST API request object containing survey data.
		 *
		 * @return WP_REST_Response Returns a WP_REST_Response object with success or error message.
		 */
		public function quick_update_survey( WP_REST_Request $request ) {
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid request data.', 'surveyx-builder' ) ],
					400
				);
			}

			$data = SurveyX_Admin_Helpers::recursive_sanitize( $body );

			$survey_id = absint( $data['id'] ?? 0 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing or invalid.', 'surveyx-builder' ) ],
					400
				);
			}

			if ( ! SurveyX_Admin_Db::survey_exists( $survey_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey not found.', 'surveyx-builder' ) ],
					404
				);
			}

			$blocked = $this->block_if_pro_survey( $survey_id );
			if ( $blocked ) {
				return $blocked;
			}

			// Read ONCE and reused: the base the posted settings are merged onto below, and
			// the authoritative title for the response message.
			$current_survey  = SurveyX_Admin_Db::get_survey_by_id( $survey_id );
			$stored_settings = ! empty( $current_survey['settings'] ) ? json_decode( $current_survey['settings'], true ) : [];
			if ( ! is_array( $stored_settings ) ) {
				$stored_settings = [];
			}

			/*
			 * The client posts the whole survey object (columns + decoded content merged to
			 * root), so strip the DB columns and server-owned fields: a stale copy of
			 * content/title/updated_at inside the blob would shadow the authoritative columns
			 * when get_survey_settings() merges settings back to root. `page_url` is derived
			 * server-side and only travels outward for display. `page_enabled` is a site-wide
			 * option that no longer travels with a survey at all, and stays listed so a stale
			 * copy — from an older client, or already sitting in a blob one wrote — is dropped
			 * rather than written back.
			 *
			 * Held in a variable because the SAME list is applied to the STORED blob at the
			 * merge below. Without that, a reserved key already sitting inside an old row's
			 * settings would survive every future save instead of being cleaned out.
			 */
			$reserved_keys = array_flip(
				[ 'id', 'title', 'author_id', 'survey_type', 'cover', 'status', 'content', 'draft_content', 's_mode', 'created_at', 'updated_at', 'page_url', 'page_enabled' ]
			);

			$settings_blob = array_diff_key( $data, $reserved_keys );

			// `page_mode` IS a real per-survey setting, so it is deliberately NOT on the
			// reserved list above. It arrives as free text, so clamp it here: only 'default',
			// 'page' or 'shortcode' is ever written, and anything else normalises to
			// 'default' rather than to a value that would take the page offline.
			if ( class_exists( 'SurveyX_Public_Page' ) && array_key_exists( 'page_mode', $settings_blob ) ) {
				$settings_blob['page_mode'] = SurveyX_Public_Page::sanitize_page_mode( $settings_blob['page_mode'] );
			}

			/*
			 * Merge, not replace — see the docblock. Deliberately ONE level deep
			 * (array_replace, not a recursive merge): a nested value that IS posted replaces
			 * its stored counterpart WHOLESALE, so a posted list that SHRANK (an entry the
			 * author deleted) stays shrunk instead of having the deleted entry resurrected
			 * from the stored copy.
			 */
			$settings_blob = array_replace( array_diff_key( $stored_settings, $reserved_keys ), $settings_blob );

			// Columns come from what the payload ACTUALLY carries. A default here is the same
			// bug one level quieter: `$data['survey_type'] ?? 'vote'` silently rewrote a
			// trivia survey to a vote survey whenever the key was merely absent. Both values
			// are already allowlisted by recursive_sanitize() (validate_type/validate_status).
			$update_fields = [ 'settings' => $settings_blob ];

			if ( array_key_exists( 'survey_type', $data ) ) {
				$update_fields['survey_type'] = $data['survey_type'];
			}

			if ( array_key_exists( 'status', $data ) ) {
				$update_fields['status'] = $data['status'];
			}

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
						// Falls back to the stored title: a caller owning one field (the
						// activation card posts id + status) has none to send, and the column
						// is authoritative anyway.
						$data['title'] ?? ( $current_survey['title'] ?? '' )
					),
				],
				200
			);
		}

		/**
		 * Updates an existing survey via REST API.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 *
		 * @return WP_REST_Response A REST response object containing either
		 *                          success data or an error message with HTTP status code.
		 */
		public function update_survey( WP_REST_Request $request ) {
			$body = json_decode( $request->get_body(), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Invalid request data.', 'surveyx-builder' ) ],
					400
				);
			}

			$data = SurveyX_Admin_Helpers::recursive_sanitize( $body );

			$survey_id = absint( $data['id'] ?? 0 );

			if ( ! $survey_id ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey ID is missing or invalid.', 'surveyx-builder' ) ],
					400
				);
			}

			$blocked = $this->block_if_pro_survey( $survey_id );
			if ( $blocked ) {
				return $blocked;
			}

			do_action( 'surveyx_before_doing_save', $data );

			$questions           = is_array( $data['questions'] ?? null ) ? $data['questions'] : [];
			$answers             = is_array( $data['answers'] ?? null ) ? $data['answers'] : [];
			$remove_question_ids = is_array( $data['remove_question_ids'] ?? null ) ? $data['remove_question_ids'] : [];
			$remove_answer_ids   = is_array( $data['remove_answer_ids'] ?? null ) ? $data['remove_answer_ids'] : [];
			$survey_data         = is_array( $data['survey'] ?? null ) ? $data['survey'] : [];

			$result = SurveyX_Admin_Db::update_survey_base( $survey_id, $survey_data );

			if ( false === $result ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Error during saving survey. Please try again.', 'surveyx-builder' ) ],
					500
				);
			}

			$id_mapping = SurveyX_Admin_Db::update_questions_and_answers_base( $survey_id, $questions, $answers );

			SurveyX_Admin_Db::delete_questions( $survey_id, $remove_question_ids );
			SurveyX_Admin_Db::delete_answers( $survey_id, $remove_answer_ids );

			/*
			 * Deleting questions/answers removes their responses. SurveyX_Db::VOTE_CACHE_TTL
			 * is 12 HOURS and is a ceiling, not a freshness window — explicit flushes like
			 * this one are what keep counts current, so dropping this call strands
			 * pre-deletion counts in /vote-results for up to half a day. The saved action is
			 * fired here too because delete_questions()/delete_answers() are the LAST writes
			 * of the save and do not fire it themselves: a concurrent /init that rebuilt the
			 * static cache after the earlier save but before these deletes would otherwise
			 * pin the removed question or answer for the full TTL.
			 */
			if ( ! empty( $remove_question_ids ) || ! empty( $remove_answer_ids ) ) {
				SurveyX_Db::flush_vote_cache( $survey_id );
				do_action( 'surveyx_survey_saved', $survey_id );
			}

			if ( ! empty( $id_mapping['questions'] ) || ! empty( $id_mapping['answers'] ) ) {
				SurveyX_Revisions::update_ids_in_revisions( $survey_id, $id_mapping );
			}

			SurveyX_Revisions::delete_autosave( $survey_id );

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
		 * Builds the list of image sizes the admin can pick for front-end display:
		 * 'full' (original) plus every image size currently registered in WordPress
		 * (core + theme/plugin), each with a human label and dimensions.
		 *
		 * @return array List of [ 'value', 'label', 'width', 'height' ].
		 */
		private function get_registered_image_sizes() {
			// '' is the "no explicit choice" state every untouched install is already in.
			// It must be offered as a real option because the settings screen posts the whole
			// blob back: without it the first save of ANY unrelated setting silently pins the
			// size to 'full' and loses the per-role defaults for good.
			$sizes = [
				[
					'value'  => '',
					'label'  => esc_html__( 'Automatic (answers medium, others full size)', 'surveyx-builder' ),
					'width'  => 0,
					'height' => 0,
				],
				[
					'value'  => 'full',
					'label'  => esc_html__( 'Full size (original)', 'surveyx-builder' ),
					'width'  => 0,
					'height' => 0,
				],
			];

			$registered = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : [];

			foreach ( $registered as $name => $data ) {
				$width  = isset( $data['width'] ) ? (int) $data['width'] : 0;
				$height = isset( $data['height'] ) ? (int) $data['height'] : 0;
				$label  = ucwords( str_replace( [ '_', '-' ], ' ', $name ) );

				if ( $width || $height ) {
					$label .= sprintf( ' (%d×%d)', $width, $height );
				}

				$sizes[] = [
					'value'  => $name,
					'label'  => $label,
					'width'  => $width,
					'height' => $height,
				];
			}

			return $sizes;
		}

		/**
		 * Get SurveyX settings via REST API.
		 *
		 * Also returns the pickable image sizes and whether the currently stored size
		 * is no longer registered (so the UI can warn that full-size images are used),
		 * plus the survey page URL base and the pieces the UI shows around it.
		 *
		 * @return WP_REST_Response Settings data.
		 */
		public function get_settings() {
			$settings    = SurveyX_Admin_Db::get_settings();
			$image_sizes = $this->get_registered_image_sizes();

			// '' (automatic) and 'full' never point at a generated file, so neither can go
			// missing when a theme that registered a custom size is switched away.
			$current = ! empty( $settings['frontend_image_size'] ) ? $settings['frontend_image_size'] : '';
			$missing = (
				'' !== $current
				&& 'full' !== $current
				&& ! in_array( $current, wp_list_pluck( $image_sizes, 'value' ), true )
			);

			return new WP_REST_Response(
				[
					'data'                   => $settings,
					'image_sizes'            => $image_sizes,
					'image_size_missing'     => $missing,
					// The URL base and the on/off switch live in their own options, never in
					// the settings blob.
					'page_base'              => class_exists( 'SurveyX_Public_Page' ) ? SurveyX_Public_Page::get_base() : '',
					// Same convention as the admin bootstrap: ON only when the class that serves
					// standalone pages is present, so a partial install never advertises a
					// feature nothing can deliver.
					'page_enabled'           => class_exists( 'SurveyX_Public_Page' ) && SurveyX_Public_Page::is_enabled(),
					'page_url_prefix'        => home_url( '/' ),
					'page_pretty_permalinks' => (bool) get_option( 'permalink_structure' ),
					// Exposed so the settings screen can refuse a reserved base before posting,
					// without a second copy of the list in JS. SurveyX_Public_Page::save_base()
					// stays the authoritative check.
					'page_reserved_bases'    => class_exists( 'SurveyX_Public_Page' ) ? SurveyX_Public_Page::RESERVED_BASES : [],
				],
				200
			);
		}

		/**
		 * Updates the site-wide SurveyX settings blob.
		 *
		 * The survey page base and its on/off switch are pulled out of the payload and stored
		 * in their own options; everything else is merged into `surveyx_settings`.
		 *
		 * @param WP_REST_Request $request The REST request object containing the settings data in the request body.
		 *
		 * @return WP_REST_Response A response object containing a success or error message and an appropriate HTTP status code.
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

			/*
			 * MERGE onto the stored blob, for the same reason quick_update_survey() does:
			 * this option is written with update_option(), so whatever arrives becomes the
			 * WHOLE of the site's settings. The screen posts the complete blob today, which
			 * is the only reason nothing has gone wrong — a caller that posts less silently
			 * destroys the rest, and captcha secrets live in here. A save firing before the
			 * settings fetch resolves would be enough.
			 *
			 * One level deep, and a key the caller DID send always wins, including false, 0
			 * and '' — so clearing a key or switching something off persists. Only keys the
			 * payload never mentions are inherited.
			 */
			$stored = get_option( 'surveyx_settings', [] );

			if ( is_array( $stored ) && ! empty( $stored ) ) {
				$sanitized = array_replace( $stored, $sanitized );
			}

			// The URL base drives rewrite rules, so it lives in its own option and must
			// never end up inside the settings blob.
			$has_page_base = array_key_exists( 'page_base', $sanitized );
			$raw_page_base = $has_page_base ? $sanitized['page_base'] : '';
			unset( $sanitized['page_base'] );

			// The on/off switch is a plain option too, and is never part of the blob.
			$has_page_enabled = array_key_exists( 'page_enabled', $sanitized );
			$raw_page_enabled = $has_page_enabled ? $sanitized['page_enabled'] : true;
			unset( $sanitized['page_enabled'] );

			$result = SurveyX_Admin_Db::update_settings( $sanitized );

			if ( false === $result ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Failed to update settings. Please try again.', 'surveyx-builder' ) ],
					500
				);
			}

			$response = [
				// translators: %s is the setting category name (e.g., "Email Notifications", "Alphabet Labels")
				'message' => esc_html__( '%s updated successfully', 'surveyx-builder' ),
			];

			if ( $has_page_base && class_exists( 'SurveyX_Public_Page' ) ) {
				$saved_base = SurveyX_Public_Page::save_base( $raw_page_base );

				// Echoed back so the UI snaps to the stored value when the posted one was
				// normalised or refused. `warning_blocked` separates a refusal (nothing was
				// stored) from an advisory about a base that WAS stored; the settings screen
				// styles the two differently.
				$response['page_base']       = $saved_base['base'];
				$response['warning']         = $saved_base['warning'];
				$response['warning_blocked'] = $saved_base['blocked'];
			}

			// Storing the switch never touches the rewrite rules or their stamp: a toggle
			// costs one option write and no flush.
			if ( $has_page_enabled && class_exists( 'SurveyX_Public_Page' ) ) {
				$response['page_enabled'] = SurveyX_Public_Page::save_enabled( $raw_page_enabled );
			}

			return new WP_REST_Response( $response, 200 );
		}

		/**
		 * Fetches survey templates from the remote API server.
		 *
		 * @param WP_REST_Request $_request The REST API request object.
		 *
		 * @return WP_REST_Response data: array of templates (empty when unavailable).
		 * @since 1.0.0
		 */
		public function fetch_remote_templates( WP_REST_Request $_request ) {

			// No server-side transient: the admin caches templates in localStorage, so this
			// always fetches fresh and the "Refresh" button returns genuinely current data.
			$templates = [];

			$response = wp_remote_post(
				SURVEYX_HOST_BASE . '/templates/wp-json/surveyx/templates',
				SurveyX_Admin_Helpers::get_remote_request_args()
			);

			if ( ! is_wp_error( $response ) ) {
				$body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $body_decoded['data'] ) ) {
					$templates = SurveyX_Admin_Helpers::recursive_sanitize( $body_decoded['data'] );
				}
			}

			if ( empty( $templates ) ) {
				return new WP_REST_Response(
					[
						'data'    => [],
						'message' => esc_html__( 'No templates available. Could not fetch from the remote server.', 'surveyx-builder' ),
					],
					500
				);
			}

			return new WP_REST_Response(
				[
					'data'    => $templates,
					'message' => esc_html__( 'Templates retrieved successfully.', 'surveyx-builder' ),
				],
				200
			);
		}

		/**
		 * Fetch remote docs from the external API for the help page.
		 *
		 * @param WP_REST_Request $_request The REST API request object.
		 * @return WP_REST_Response Docs data or error.
		 */
		public function fetch_remote_docs( WP_REST_Request $_request ) {
			// No server-side transient, for the same reason as fetch_remote_templates().
			$docs = [];

			$response = wp_remote_post(
				SURVEYX_HOST_BASE . '/templates/wp-json/surveyx/docs',
				SurveyX_Admin_Helpers::get_remote_request_args()
			);

			if ( ! is_wp_error( $response ) ) {
				$body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $body_decoded['data'] ) ) {
					$docs = SurveyX_Admin_Helpers::recursive_sanitize( $body_decoded['data'] );
				}
			}

			if ( empty( $docs ) ) {
				return new WP_REST_Response(
					[
						'data'    => [],
						'message' => esc_html__( 'No docs available. Could not fetch from the remote server.', 'surveyx-builder' ),
					],
					500
				);
			}

			return new WP_REST_Response(
				[
					'data'    => $docs,
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
			$timeout = DAY_IN_SECONDS;

			if ( SurveyX_Admin_Helpers::is_dev_mode() ) {
				$timeout = 300;
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

			$type = sanitize_text_field( $body['type'] ?? 'autosave' );
			if ( ! in_array( $type, [ 'autosave', 'snapshot', 'manual' ], true ) ) {
				$type = 'autosave';
			}

			$data = [
				'survey'    => SurveyX_Admin_Helpers::recursive_sanitize( $body['survey'] ?? [] ),
				'questions' => SurveyX_Admin_Helpers::recursive_sanitize( $body['questions'] ?? [] ),
				'answers'   => SurveyX_Admin_Helpers::recursive_sanitize( $body['answers'] ?? [] ),
			];

			// null when an interval-based snapshot is skipped.
			$revision_id = SurveyX_Revisions::save_revision( $survey_id, $data, $type );

			// Raw UTC string, uniform with data.revisions[].created_at and every other
			// endpoint; the admin client runs it through its UTC-aware formatter.
			$saved_at_utc = surveyx_get_utc_now();

			return new WP_REST_Response(
				[
					'success'     => true,
					'revision_id' => $revision_id,
					'saved_at'    => $saved_at_utc,
					'type'        => $type,
					// Same shape as GET /revisions, so the editor refreshes history without a
					// second request.
					'data'        => [
						'revisions' => SurveyX_Revisions::get_revisions( $survey_id, 5 ),
					],
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

			$survey          = SurveyX_Admin_Db::get_survey_by_id( $survey_id );
			$last_updated_at = $survey['updated_at'] ?? null;

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
		 *
		 * Synchronous: import_handler writes step-by-step progress to a transient the client
		 * polls via /import/progress in parallel, and this request returns the final survey_id
		 * once processing finishes. The media step is time-bounded, so it always completes.
		 *
		 * @param WP_REST_Request $request The REST API request object.
		 * @return WP_REST_Response Response with import_id.
		 */
		public function start_import( WP_REST_Request $request ) {

			// Allow the bounded, long-running import to complete.
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 );
			}

			$body           = json_decode( $request->get_body(), true );
			$template_id    = sanitize_text_field( $body['template_id'] ?? '' );
			$title_override = sanitize_text_field( (string) ( $body['title'] ?? '' ) );

			if ( empty( $template_id ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Template ID is required.', 'surveyx-builder' ) ],
					400
				);
			}

			require_once SURVEYX_PATH . 'admin/import-handler.php';

			// Derived from template_id rather than generated, so the client can start polling
			// /import/progress before this request returns.
			$import_id = 'tpl_' . $template_id;

			$result = SurveyX_Import_Handler::process_import( $import_id, $template_id, $title_override );

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

SurveyX_Admin_API::get_instance();
