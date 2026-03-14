<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SurveyX Import Handler
 *
 * Handles server-side template import with progress tracking via WordPress transients.
 * Provides real-time progress updates during template download, media import, and database import.
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
		 * Generate unique import ID.
		 *
		 * @return string Unique import ID.
		 */
		public static function generate_import_id() {
			return wp_generate_uuid4();
		}

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
		 * Delete import progress transient.
		 *
		 * @param string $import_id Import ID.
		 * @return bool True on success.
		 */
		public static function delete_progress( $import_id ) {
			return delete_transient( self::TRANSIENT_PREFIX . $import_id );
		}

		/**
		 * Process template import with progress tracking.
		 *
		 * @param string $import_id Import ID for tracking.
		 * @param string $template_id Template ID from remote server.
		 * @return array Result with survey_id on success or error.
		 */
		public static function process_import( $import_id, $template_id ) {
			try {
				// Step 1: Get template data from cache or fetch from server
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

				// Step 2: Import ALL media (including theme background_image)
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

				// Step 3: Allow process additional data
				$processed_data = apply_filters( 'surveyx_import_processed_data', $processed_data, $import_id );

				// Step 4: Import to database
				$result = self::import_to_database( $import_id, $processed_data );

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

				// Step 5: Complete - transient will auto-expire after 30 seconds
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
		 * Get template data from cache or fetch from server.
		 * Uses cached data if available (from preview), otherwise fetches fresh.
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

			// Check cache first (already fetched during preview)
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

			// Fallback: fetch from external API (if cache expired)
			return self::fetch_template_from_api( $import_id, $template_id );
		}

		/**
		 * Fetch template data from external API.
		 * Called when cache is empty or expired.
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
				// Try to get error message from response body
				$error_body    = wp_remote_retrieve_body( $response );
				$error_data    = json_decode( $error_body, true );
				$error_message = $error_data['message'] ?? '';

				// For 403 with license_required code, show specific message
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

			// Cache for future use
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

			// Find all external images
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

			// Process each image
			$url_mapping = [];
			$current     = 0;

			foreach ( $external_images as $image_info ) {
				++$current;
				$url = $image_info['url'];

				// Update progress
				$progress_percent = 30 + ( 30 * ( $current / $total_images ) );
				self::update_progress(
					$import_id,
					[
						'progress'      => round( $progress_percent ),
						'message'       => esc_html__( 'Uploading media...', 'surveyx-builder' ),
						'media_current' => $current,
					]
				);

				// Upload the image
				$result = SurveyX_Media_Helper::upload_image( $url );

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

			// Replace URLs in data
			$processed_data = self::replace_urls_in_data( $data, $url_mapping );

			self::update_progress(
				$import_id,
				[
					'progress' => 60,
					'message'  => sprintf(
						/* translators: %d: number of media files */
						esc_html__( 'Media import complete (%d files).', 'surveyx-builder' ),
						count( $url_mapping )
					),
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
					// Replace URL with new URL
					$processed[ $key ] = $url_mapping[ $value ]['url'];
					// Add image_id if key is image-related
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
		 * @return array|WP_Error Result with survey_id or error.
		 */
		private static function import_to_database( $import_id, $data ) {
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

			if ( empty( $survey_data ) || empty( $survey_data['title'] ) ) {
				return new WP_Error( 'invalid_data', esc_html__( 'Invalid template data for importing.', 'surveyx-builder' ) );
			}

			// Create new survey
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

			// Set survey data (before question import - will update mentions after)
			SurveyX_Admin_Db::import_survey_data( $survey_id, $survey_data );

			self::update_progress(
				$import_id,
				[
					'progress' => 90,
					'message'  => esc_html__( 'Importing questions and answers...', 'surveyx-builder' ),
				]
			);

			// Import questions and answers - get ID mapping
			$id_mapping = SurveyX_Admin_Db::update_questions_and_answers_base( $survey_id, $questions, $answers );

			/**
			 * Action hook for post-import processing (Pro: remap recall mentions).
			 *
			 * @param int    $survey_id  The new survey ID.
			 * @param array  $id_mapping ID mapping ['questions' => [old => new], 'answers' => [old => new]].
			 * @param string $import_id  Import ID for progress tracking.
			 */
			do_action( 'surveyx_after_template_import', $survey_id, $id_mapping, $import_id );

			// Get created survey
			$created_survey = SurveyX_Admin_Db::get_survey_by_id( $survey_id );

			return [
				'survey_id' => $survey_id,
				'data'      => $created_survey,
			];
		}

		/**
		 * Get list of free themes.
		 *
		 * @return array List of free theme IDs.
		 */
		public static function get_free_themes() {
			return [ 'normal', 'sakura', 'glacier', 'frost', 'blush', 'amber' ];
		}

		/**
		 * Check if a theme is a free theme.
		 *
		 * @param string $theme Theme ID.
		 * @return bool True if free theme.
		 */
		public static function is_free_theme( $theme ) {
			return in_array( $theme, self::get_free_themes(), true );
		}

		/**
		 * Validate and normalize theme during import.
		 * Falls back to default theme if the imported theme is not available.
		 *
		 * @param array $data Processed template data.
		 * @return array Filtered data with validated theme.
		 */
		public static function validate_imported_theme( $data ) {
			// Validate survey settings theme
			if ( isset( $data['survey']['settings']['theme'] ) ) {
				$theme = $data['survey']['settings']['theme'];
				if ( ! self::is_free_theme( $theme ) ) {
					$data['survey']['settings']['theme'] = 'normal';
				}
			}

			return $data;
		}
	}

	// Register theme validation filter for import
	add_filter( 'surveyx_import_processed_data', [ 'SurveyX_Import_Handler', 'validate_imported_theme' ] );
}
