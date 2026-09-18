<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side template import, reporting progress through a WP transient the admin
 * polls: download -> media sideload -> database.
 *
 * @since 1.0.0
 */
if ( ! class_exists( 'SurveyX_Import_Handler' ) ) {
	class SurveyX_Import_Handler {

		/**
		 * Transient prefix for import progress.
		 */
		const TRANSIENT_PREFIX = 'surveyx_import_';

		/**
		 * Transient expiry time (30 seconds after import complete).
		 */
		const TRANSIENT_EXPIRY = 30;

		/**
		 * Update import progress in transient.
		 *
		 * @param string $import_id Import ID.
		 * @param array  $data Progress data.
		 * @return bool True on success.
		 */
		public static function update_progress( $import_id, $data ) {
			$current = self::get_progress( $import_id ) ?: [];
			$updated = array_merge( $current, $data );
			return set_transient( self::TRANSIENT_PREFIX . $import_id, $updated, self::TRANSIENT_EXPIRY );
		}

		/**
		 * Get import progress from transient.
		 *
		 * @param string $import_id Import ID.
		 * @return array|false Progress data or false if not found.
		 */
		public static function get_progress( $import_id ) {
			return get_transient( self::TRANSIENT_PREFIX . $import_id );
		}

		/**
		 * Process template import with progress tracking.
		 *
		 * @param string $import_id Import ID for tracking.
		 * @param string $template_id Template ID from remote server.
		 * @param string $title_override Optional title to override the template's own title.
		 * @return array Result with survey_id on success or error.
		 */
		public static function process_import( $import_id, $template_id, $title_override = '' ) {
			try {
				$template_data = self::get_template_data( $import_id, $template_id );

				if ( is_wp_error( $template_data ) ) {
					self::update_progress(
						$import_id,
						[
							'status'  => 'error',
							'error'   => $template_data->get_error_message(),
							'message' => $template_data->get_error_message(),
						]
					);
					return [ 'error' => $template_data->get_error_message() ];
				}

				$processed_data = self::import_media( $import_id, $template_data );

				if ( is_wp_error( $processed_data ) ) {
					self::update_progress(
						$import_id,
						[
							'status'  => 'error',
							'error'   => $processed_data->get_error_message(),
							'message' => $processed_data->get_error_message(),
						]
					);
					return [ 'error' => $processed_data->get_error_message() ];
				}

				$processed_data = apply_filters( 'surveyx_import_processed_data', $processed_data, $import_id );

				$result = self::import_to_database( $import_id, $processed_data, $title_override );

				if ( is_wp_error( $result ) ) {
					self::update_progress(
						$import_id,
						[
							'status'  => 'error',
							'error'   => $result->get_error_message(),
							'message' => $result->get_error_message(),
						]
					);
					return [ 'error' => $result->get_error_message() ];
				}

				self::update_progress(
					$import_id,
					[
						'status'    => 'complete',
						'step'      => 'complete',
						'progress'  => 100,
						'message'   => esc_html__( 'Import complete!', 'surveyx-builder' ),
						'survey_id' => $result['survey_id'],
						'data'      => $result['data'],
					]
				);

				return $result;
			} catch ( Exception $e ) {
				self::update_progress(
					$import_id,
					[
						'status'  => 'error',
						'error'   => $e->getMessage(),
						'message' => $e->getMessage(),
					]
				);
				return [ 'error' => $e->getMessage() ];
			}
		}

		/**
		 * Get template data, preferring the transient the preview request already filled.
		 *
		 * @param string $import_id Import ID.
		 * @param string $template_id Template ID.
		 * @return array|WP_Error Template data or error.
		 */
		private static function get_template_data( $import_id, $template_id ) {
			self::update_progress(
				$import_id,
				[
					'status'   => 'processing',
					'step'     => 'download',
					'progress' => 10,
					'message'  => esc_html__( 'Loading template data...', 'surveyx-builder' ),
				]
			);

			$cache_key   = 'surveyx_template_' . $template_id;
			$cached_data = get_transient( $cache_key );

			if ( false !== $cached_data ) {
				self::update_progress(
					$import_id,
					[
						'progress' => 20,
						'message'  => esc_html__( 'Template data loaded from cache.', 'surveyx-builder' ),
					]
				);
				return $cached_data;
			}

			return self::fetch_template_from_api( $import_id, $template_id );
		}

		/**
		 * Fetch template data from the external API.
		 *
		 * @param string $import_id Import ID.
		 * @param string $template_id Template ID.
		 * @return array|WP_Error Template data or error.
		 */
		private static function fetch_template_from_api( $import_id, $template_id ) {
			self::update_progress(
				$import_id,
				[
					'message' => esc_html__( 'Downloading template...', 'surveyx-builder' ),
				]
			);

			$response = wp_remote_post(
				SURVEYX_HOST_BASE . '/templates/wp-json/surveyx/template/' . $template_id,
				SurveyX_Admin_Helpers::get_remote_request_args( true )
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'download_failed',
					sprintf(
						/* translators: %s: error message */
						esc_html__( 'Failed to download template: %s', 'surveyx-builder' ),
						$response->get_error_message()
					)
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$error_body    = wp_remote_retrieve_body( $response );
				$error_data    = json_decode( $error_body, true );
				$error_message = $error_data['message'] ?? '';

				if ( 403 === $code && ( $error_data['code'] ?? '' ) === 'license_required' ) {
					return new WP_Error( 'license_required', esc_html__( 'A valid license is required to import this template.', 'surveyx-builder' ) );
				}

				return new WP_Error(
					'download_failed',
					$error_message
						? $error_message
						: sprintf(
							/* translators: %d: HTTP response code */
							esc_html__( 'Template download failed with status: %d', 'surveyx-builder' ),
							$code
						)
				);
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() || empty( $data['data'] ) ) {
				return new WP_Error( 'invalid_template', esc_html__( 'Invalid template data received.', 'surveyx-builder' ) );
			}

			$template_data = SurveyX_Admin_Helpers::recursive_sanitize( $data['data'] );

			// Same key get_template_data() reads, so a retry skips the fetch.
			$cache_key = 'surveyx_template_' . $template_id;
			set_transient( $cache_key, $template_data, 600 );

			self::update_progress(
				$import_id,
				[
					'progress' => 20,
					'message'  => esc_html__( 'Template downloaded successfully.', 'surveyx-builder' ),
				]
			);

			return $template_data;
		}

		/**
		 * Find all external image URLs in data.
		 *
		 * @param array  $data Data to scan.
		 * @param string $path Current path for tracking.
		 * @return array List of external image URLs with their paths.
		 */
		private static function find_external_images( $data, $path = '' ) {
			$images = [];

			if ( ! is_array( $data ) ) {
				return $images;
			}

			require_once SURVEYX_PATH . 'admin/media-helpers.php';

			foreach ( $data as $key => $value ) {
				$current_path = $path ? $path . '.' . $key : $key;

				if ( is_string( $value ) && ! empty( $value ) ) {
					// Check if it's an external image URL
					if (
						filter_var( $value, FILTER_VALIDATE_URL )
						&& SurveyX_Media_Helper::is_external_url( $value )
						&& self::looks_like_image_url( $value )
					) {
						$images[] = [
							'url'  => $value,
							'path' => $current_path,
						];
					}
				} elseif ( is_array( $value ) ) {
					$images = array_merge( $images, self::find_external_images( $value, $current_path ) );
				}
			}

			return $images;
		}

		/**
		 * Check if URL looks like an image.
		 *
		 * @param string $url URL to check.
		 * @return bool True if looks like image URL.
		 */
		private static function looks_like_image_url( $url ) {
			$extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];
			$path       = wp_parse_url( $url, PHP_URL_PATH );

			if ( empty( $path ) ) {
				return false;
			}

			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			return in_array( $ext, $extensions, true );
		}

		/**
		 * Import media files with progress tracking.
		 *
		 * @param string $import_id Import ID.
		 * @param array  $data Template data.
		 * @return array|WP_Error Processed data or error.
		 */
		private static function import_media( $import_id, $data ) {
			self::update_progress(
				$import_id,
				[
					'step'     => 'media',
					'progress' => 25,
					'message'  => esc_html__( 'Analyzing media files...', 'surveyx-builder' ),
				]
			);

			$external_images = self::find_external_images( $data );
			$total_images    = count( $external_images );

			if ( 0 === $total_images ) {
				self::update_progress(
					$import_id,
					[
						'progress'      => 60,
						'message'       => esc_html__( 'No external media to import.', 'surveyx-builder' ),
						'media_total'   => 0,
						'media_current' => 0,
					]
				);
				return $data;
			}

			self::update_progress(
				$import_id,
				[
					'progress'      => 30,
					'message'       => sprintf(
						/* translators: %d: number of media files */
						esc_html__( 'Found %d media files to import...', 'surveyx-builder' ),
						$total_images
					),
					'media_total'   => $total_images,
					'media_current' => 0,
				]
			);

			require_once SURVEYX_PATH . 'admin/media-helpers.php';

			// A short per-image timeout plus a cumulative wall-clock budget stop stalled remote
			// images hanging the whole import. Images left when the budget expires keep their
			// original remote URLs and the survey still imports.
			$url_mapping   = [];
			$current       = 0;
			$skipped       = 0;
			$image_timeout = 15; // Per-image timeout (seconds) for HEAD + download.
			$time_budget   = 45; // Cumulative budget (seconds) for all media.
			$start         = microtime( true );

			foreach ( $external_images as $image_info ) {
				if ( microtime( true ) - $start > $time_budget ) {
					$skipped = $total_images - $current;
					break;
				}

				++$current;
				$url = $image_info['url'];

				$progress_percent = 30 + ( 30 * ( $current / $total_images ) );
				self::update_progress(
					$import_id,
					[
						'progress'      => round( $progress_percent ),
						'message'       => esc_html__( 'Uploading media...', 'surveyx-builder' ),
						'media_current' => $current,
					]
				);

				$result = SurveyX_Media_Helper::upload_image( $url, '', $image_timeout );

				if ( ! is_wp_error( $result ) ) {
					$url_mapping[ $url ] = [
						'url' => $result['url'],
						'id'  => $result['id'],
					];
				} else {
					// Log error but continue with other images
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
					error_log( sprintf( 'SurveyX Import: Failed to upload %s - %s', $url, $result->get_error_message() ) );
				}
			}

			if ( $skipped > 0 ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging
				error_log( sprintf( 'SurveyX Import: Media time budget (%1$ds) exceeded, skipped %2$d remaining image(s); their original remote URLs are kept.', $time_budget, $skipped ) );
			}

			$processed_data = self::replace_urls_in_data( $data, $url_mapping );

			$complete_message = sprintf(
				/* translators: %d: number of media files */
				esc_html__( 'Media import complete (%d files).', 'surveyx-builder' ),
				count( $url_mapping )
			);
			if ( $skipped > 0 ) {
				$complete_message = sprintf(
					/* translators: 1: number of imported files, 2: number of skipped files */
					esc_html__( 'Media import finished (%1$d imported, %2$d skipped).', 'surveyx-builder' ),
					count( $url_mapping ),
					$skipped
				);
			}

			self::update_progress(
				$import_id,
				[
					'progress' => 60,
					'message'  => $complete_message,
				]
			);

			return $processed_data;
		}

		/**
		 * Replace external URLs with uploaded URLs in data.
		 *
		 * @param array $data Data to process.
		 * @param array $url_mapping Map of old URLs to new URLs and IDs.
		 * @return array Processed data.
		 */
		private static function replace_urls_in_data( $data, $url_mapping ) {
			if ( ! is_array( $data ) ) {
				return $data;
			}

			$processed = [];

			foreach ( $data as $key => $value ) {
				if ( is_string( $value ) && isset( $url_mapping[ $value ] ) ) {
					$processed[ $key ] = $url_mapping[ $value ]['url'];
					if ( in_array( $key, [ 'image', 'cover', 'background', 'logo' ], true ) ) {
						$processed['image_id'] = $url_mapping[ $value ]['id'];
					}
				} elseif ( is_array( $value ) ) {
					$processed[ $key ] = self::replace_urls_in_data( $value, $url_mapping );
				} else {
					$processed[ $key ] = $value;
				}
			}

			return $processed;
		}

		/**
		 * Import processed data to database.
		 *
		 * @param string $import_id Import ID.
		 * @param array  $data Processed template data.
		 * @param string $title_override Optional title to override the template's own title.
		 * @param string $source Origin of the payload: 'template' (a new survey built from a
		 *                       template) or 'file' (restoring a previously exported survey).
		 * @return array|WP_Error Result with survey_id or error.
		 */
		private static function import_to_database( $import_id, $data, $title_override = '', $source = 'template' ) {
			self::update_progress(
				$import_id,
				[
					'step'     => 'database',
					'progress' => 70,
					'message'  => esc_html__( 'Creating survey...', 'surveyx-builder' ),
				]
			);

			$questions   = is_array( $data['questions'] ?? null ) ? $data['questions'] : [];
			$answers     = is_array( $data['answers'] ?? null ) ? $data['answers'] : [];
			$survey_data = is_array( $data['survey'] ?? null ) ? $data['survey'] : [];

			if ( '' !== $title_override ) {
				$survey_data['title'] = $title_override;
			}

			if ( empty( $survey_data ) || empty( $survey_data['title'] ) ) {
				return new WP_Error( 'invalid_data', esc_html__( 'Invalid template data for importing.', 'surveyx-builder' ) );
			}

			$survey_type = $survey_data['survey_type'] ?? 'vote';
			$survey_id   = SurveyX_Admin_Db::create_survey( $survey_data['title'], $survey_type );

			if ( empty( $survey_id ) ) {
				return new WP_Error( 'create_failed', esc_html__( 'Failed to create survey in database.', 'surveyx-builder' ) );
			}

			self::update_progress(
				$import_id,
				[
					'progress' => 80,
					'message'  => esc_html__( 'Importing survey content...', 'surveyx-builder' ),
				]
			);

			if ( isset( $survey_data['settings']['id'] ) ) {
				$survey_data['settings']['id'] = $survey_id;
			}

			if ( isset( $survey_data['content']['id'] ) ) {
				$survey_data['content']['id'] = $survey_id;
			}

			// import_survey_data() replaces the whole content column, dropping the cover_layout
			// create_survey() just seeded. Re-apply it for a TEMPLATE so a new survey follows the
			// site-wide Default Cover Layout, unless the template states its own layout (template
			// wins). A FILE import restores an authored survey: an absent key stays absent and
			// keeps rendering 'stacked'.
			if ( 'template' === $source
				&& ! empty( $survey_data['content'] ) && is_array( $survey_data['content'] )
				&& ! array_key_exists( 'cover_layout', $survey_data['content'] )
			) {
				$survey_data['content']['cover_layout'] = 'default';
			}

			// Same reason as cover_layout above: create_survey() seeded content['title'] with the
			// name typed in the wizard, but the template's content carries the TEMPLATE's title.
			// The editor binds that copy, so the first save/autosave would write it back into the
			// title column and lose the typed name.
			if ( '' !== $title_override && ! empty( $survey_data['content'] ) && is_array( $survey_data['content'] ) ) {
				$survey_data['content']['title'] = $title_override;
			}

			// Before the question import; surveyx_after_template_import remaps mentions after both.
			SurveyX_Admin_Db::import_survey_data( $survey_id, $survey_data );

			self::update_progress(
				$import_id,
				[
					'progress' => 90,
					'message'  => esc_html__( 'Importing questions and answers...', 'surveyx-builder' ),
				]
			);

			$id_mapping = SurveyX_Admin_Db::update_questions_and_answers_base( $survey_id, $questions, $answers );

			// create_survey() seeded 'basic' before these rows existed. A template carrying
			// more than one question - or one question of a type this edition cannot render -
			// is Pro, and without this the survey would stay marked basic and be offered to an
			// editor that holds a single question, which drops the rest on the next save.
			SurveyX_Db::stamp_survey_mode( $survey_id );

			/**
			 * Action hook for post-import processing (Pro: remap recall mentions).
			 *
			 * @param int    $survey_id  The new survey ID.
			 * @param array  $id_mapping ID mapping ['questions' => [old => new], 'answers' => [old => new]].
			 * @param string $import_id  Import ID for progress tracking.
			 */
			do_action( 'surveyx_after_template_import', $survey_id, $id_mapping, $import_id );

			$created_survey = SurveyX_Admin_Db::get_survey_by_id( $survey_id );

			return [
				'survey_id' => $survey_id,
				'data'      => $created_survey,
			];
		}

		/**
		 * Get list of basic themes.
		 *
		 * @return array List of basic theme IDs.
		 */
		public static function get_basic_themes() {
			return [ 'normal', 'sakura', 'glacier', 'frost', 'blush', 'amber' ];
		}

		/**
		 * Check if a theme is a basic theme.
		 *
		 * @param string $theme Theme ID.
		 * @return bool True if basic theme.
		 */
		public static function is_basic_theme( $theme ) {
			return in_array( $theme, self::get_basic_themes(), true );
		}

		/**
		 * Validate and normalize theme during import.
		 * Falls back to default theme if the imported theme is not available.
		 *
		 * @param array $data Processed template data.
		 * @return array Filtered data with validated theme.
		 */
		public static function validate_imported_theme( $data ) {
			if ( isset( $data['survey']['settings']['theme'] ) ) {
				$theme = $data['survey']['settings']['theme'];
				if ( ! self::is_basic_theme( $theme ) ) {
					$data['survey']['settings']['theme'] = 'normal';
				}
			}

			return $data;
		}
	}

	add_filter( 'surveyx_import_processed_data', [ 'SurveyX_Import_Handler', 'validate_imported_theme' ] );
}
